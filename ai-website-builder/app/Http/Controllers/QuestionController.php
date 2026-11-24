<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSite;
use App\Models\Question;
use App\Services\QuestionAnswerer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function answer(Request $request, Question $question, QuestionAnswerer $answerer): RedirectResponse
    {
        $data = $request->validate(['choice' => 'nullable|string|max:50', 'custom' => 'nullable|string|max:20000']);
        if (blank($data['choice'] ?? null) && blank($data['custom'] ?? null)) {
            return back()->withErrors(['answer' => 'Pick an option or type an answer (or press Skip).']);
        }
        if ($question->status === 'open') {
            $answerer->answer($question, $data['choice'] ?? null, $data['custom'] ?? null);
        }

        return $this->continue($question);
    }

    public function skip(Question $question, QuestionAnswerer $answerer): RedirectResponse
    {
        if ($question->status === 'open') {
            $answerer->skip($question);
        }

        return $this->continue($question);
    }

    private function continue(Question $question): RedirectResponse
    {
        $site = $question->site;
        $site->moveTo('queued', 'Using your answer');
        ProcessSite::dispatch($site->id);

        return redirect()->route('sites.show', $site)->with('ok', 'Thanks - updating your site.');
    }
}
