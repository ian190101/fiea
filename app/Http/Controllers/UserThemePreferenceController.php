<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class UserThemePreferenceController extends Controller
{
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'theme_preference' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        $request->user()->forceFill($validated)->save();

        return response()->noContent();
    }
}
