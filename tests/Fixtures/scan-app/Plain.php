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
