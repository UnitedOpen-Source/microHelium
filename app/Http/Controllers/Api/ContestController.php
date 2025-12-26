<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContestController extends Controller
{
    public function index(): JsonResponse
    {
        $contests = Contest::query()
            ->when(!auth()->user()?->isAdmin(), fn($q) => $q->where('is_public', true))
            ->withCount(['problems', 'users', 'runs'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($contests);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'start_time' => 'nullable|date',
            'duration' => 'required|integer|min:1|max:10080',
            'freeze_time' => 'required|integer|min:0',
            'penalty' => 'required|integer|min:0|max:120',
            'max_file_size' => 'required|integer|min:1|max:10240',
            'is_public' => 'boolean',
        ]);

        $contest = Contest::create($validated);

        // Create default site
        Site::create([
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
        ]);

        // Create default languages
        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }

        // Create default answers
        foreach (Answer::getDefaultAnswers() as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        return response()->json($contest->load(['sites', 'languages', 'answers']), 201);
    }

    public function show(Contest $contest): JsonResponse
    {
        $contest->load([
            'sites',
            'languages' => fn($q) => $q->where('is_active', true),
            'answers' => fn($q) => $q->orderBy('sort_order'),
            'problems' => fn($q) => $q->orderBy('sort_order'),
        ]);

        $contest->loadCount(['users', 'runs', 'clarifications']);

        return response()->json($contest);
    }

    public function update(Request $request, Contest $contest): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'start_time' => 'nullable|date',
            'duration' => 'sometimes|integer|min:1|max:10080',
            'freeze_time' => 'sometimes|integer|min:0',
            'penalty' => 'sometimes|integer|min:0|max:120',
            'max_file_size' => 'sometimes|integer|min:1|max:10240',
            'is_active' => 'sometimes|boolean',
            'is_public' => 'sometimes|boolean',
        ]);

        $contest->update($validated);

        return response()->json($contest);
    }

    public function destroy(Contest $contest): JsonResponse
    {
        $contest->delete();

        return response()->json(null, 204);
    }

    public function activate(Contest $contest): JsonResponse
    {
        $contest->update(['is_active' => true]);

        return response()->json(['message' => 'Contest activated', 'contest' => $contest]);
    }

    public function deactivate(Contest $contest): JsonResponse
    {
        $contest->update(['is_active' => false]);

        return response()->json(['message' => 'Contest deactivated', 'contest' => $contest]);
    }

    public function status(Contest $contest): JsonResponse
    {
        return response()->json([
            'is_active' => $contest->is_active,
            'is_running' => $contest->isRunning(),
            'is_frozen' => $contest->isFrozen(),
            'start_time' => $contest->start_time,
            'end_time' => $contest->end_time,
            'contest_time' => $contest->getContestTime(),
            'duration' => $contest->duration,
            'freeze_time' => $contest->freeze_time,
        ]);
    }
}
