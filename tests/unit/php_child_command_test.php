<?php
declare(strict_types=1);

test('php_child_command preserves a parent-only sys_temp_dir override', function () {
    $tmp = sys_get_temp_dir() . '/builder php child ' . uniqid();
    mkdir($tmp, 0700, true);

    try {
        $bootstrap = repo_path('src/bootstrap.php');
        $childCode = 'echo sys_get_temp_dir();';
        $parentCode = 'require ' . var_export($bootstrap, true) . ';'
            . '$command = php_child_command("-r", [' . var_export($childCode, true) . ']);'
            . 'passthru($command, $exit);'
            . 'exit($exit);';

        $output = [];
        $exit = 1;
        exec(
            escapeshellarg(PHP_BINARY)
                . ' -d ' . escapeshellarg('sys_temp_dir=' . $tmp)
                . ' -r ' . escapeshellarg($parentCode)
                . ' 2>&1',
            $output,
            $exit
        );

        assert_eq(0, $exit, implode("\n", $output));
        assert_eq($tmp, implode("\n", $output));
    } finally {
        @rmdir($tmp);
    }
});
