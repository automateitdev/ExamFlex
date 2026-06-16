<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TempExamConfig;
use App\Services\ExamMarkCalculator;
use App\Services\ExamService;
use App\Services\MeritProcessor;
use App\Services\ResultCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
        // Log::channel('mark_entry_log')->info('Mark Entry Config Request', [
        //     'request' => $request->all()
        // ]);

        $data = $request->all();

        $authResult = $this->examService->authenticateRequest($request);

        if ($authResult instanceof \Illuminate\Http\JsonResponse) {
            return $authResult;
        }
        $client = $authResult;

        DB::enableQueryLog();

        $tempId = 'temp_' . Str::random(12);

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
        // Log::channel('mark_entry_log')->info('Mark Calculation Request', [
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
        // Log::channel('exam_flex_log')->info('Result Process Request', [
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

        // Log::channel('exam_flex_log')->info('Result Process Result', [
        //     'results' => $results
        // ]);
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
