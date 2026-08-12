<?php

// __('comment.example')

$inString = "text that mentions __('in.string')";
$doc = '/** @see __(\'in.docblock\') */';

__('real.literal');
trans('trans.literal');
trans_choice('choice.literal', 2);
\Lang::get('lang.get.literal');
app('translator')->get('translator.literal');

$key = 'dynamic.key';
__($key);
__("interpolated.{$key}");

// Unescaping follows the quote style the literal was written with.
__('single\quoted\backslashes');
__('escaped \\ backslash and \' quote');
__("unicode \u{1F600} escape");

// `get` and `choice` only count on a receiver that is the translator.
$request->get('request.get.key');
$menu->choice('menu.choice.key');
\Illuminate\Support\Facades\Lang::get('facade.get.literal');
Lang::choice('lang.choice.literal', 2);
app('translator')->choice('translator.choice.literal', 2);

// A qualified name arrives as a single token.
\App\Helpers\trans('namespaced.key');

// A concatenation is not a readable literal.
__('a'.'b');
