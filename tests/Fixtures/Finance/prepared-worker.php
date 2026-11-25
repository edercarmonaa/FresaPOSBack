<?php

$root = realpath(dirname(__DIR__, 3).'/.tools');
$directory = realpath($argv[1] ?? '');
$role = $argv[2] ?? '';
if ($root === false || $directory === false || strcasecmp(dirname($directory), $root) !== 0
    || ! preg_match('/\Areports-race-[a-f0-9]{12}\z/', basename($directory)) || ! in_array($role, ['first', 'second'], true)) {
    throw new RuntimeException('Invalid prepared-worker probe.');
}
$ready = ['run_id' => basename($directory), 'worker' => $role, 'pid' => getmypid(), 'spid' => 0];
file_put_contents($directory.'/'.$role.'-ready.json.tmp', json_encode($ready, JSON_THROW_ON_ERROR));
rename($directory.'/'.$role.'-ready.json.tmp', $directory.'/'.$role.'-ready.json');
$deadline = hrtime(true) + 45_000_000_000;
while (! is_file($directory.'/'.$role.'-start')) {
    if (hrtime(true) >= $deadline) {
        exit(8);
    }
    usleep(20000);
}
file_put_contents($directory.'/'.$role.'-executed', 'started');
