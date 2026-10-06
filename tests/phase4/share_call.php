<?php
/**
 * A subprocess for the shares proof: calls one share function the way the internal door does — php share_call.php <function> <member id> '<json list of the arguments after the member>' — and prints one JSON line {"doc": …} | {"refused": …} | {"error": …}.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/features/shares/queries.php';
[, $fn, $member, $args] = $argv + [null, null, '0', '[]'];
$pdo = db();
try {
    $out = ['doc' => $fn($pdo, (int) $member, ...json_decode((string) $args, true))];
} catch (ShareRefused $e) {
    $out = ['refused' => $e->getMessage()];
} catch (Throwable $e) {
    $out = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($out) . "\n";
