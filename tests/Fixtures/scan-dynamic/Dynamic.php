<?php

// A call site whose key cannot be read: the one finding this tree produces.
$key = 'some.key';
__($key);

// A literal the scan-dynamic-lang catalogue defines. It keeps the tree out of
// the "no literal translation calls were found" warning (which would withhold
// unused and make the run incomplete) without adding a missing or unused key.
__('dynamic.fixture.key');
