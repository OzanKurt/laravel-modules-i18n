<?php

// Console markup is ordinary text inside a translation key, and anything but
// that to Symfony's output formatter: "<info>" is a style it would apply and
// strip back out, "<fg=chartreuse>" a colour it does not know and refuses to
// parse at all. Both keys have to reach the terminal exactly as written.
__('Press <info>enter</info> to continue');
__('Choose a <fg=chartreuse>colour</> scheme');
