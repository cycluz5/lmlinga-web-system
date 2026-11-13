<?php

namespace App\Http\Controllers\LanguageReview;

use App\Http\Controllers\Controller;
use App\Support\LanguageReviewQueue;
use Illuminate\View\View;

/**
 * SR-06 Language Review home for Linguist accounts (route middleware `ui.linguist`).
 * SR-05 fills the terms section with submitted Bikol-Iriga terms.
 */
class LanguageReviewController extends Controller
{
    public function index(LanguageReviewQueue $queue): View
    {
        $sections = $queue->sections();

        return view('pages.language-review.index', [
            'active' => 'language-review',
            'pageTitle' => 'Language Review',
            'pageSubtitle' => 'Review Bikol-Iriga terms and chatbot datasets before the chatbot may use them.',
            'sections' => $sections,
            'pendingCount' => array_sum(array_map(static fn (array $section): int => count($section['items']), $sections)),
        ]);
    }
}
