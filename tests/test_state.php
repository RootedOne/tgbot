<?php
// FILE: tests/test_state.php
require_once 'config.php';
require_once 'functions.php';

echo "Testing State Management...\n";

$userId = 123456789; // Test ID

// 1. Ensure user does not exist in users table (clean slate if possible, but we can't delete easily without violating FKs if we don't cascade, but cascade is on)
$pdo = getPDO();
$pdo->exec("DELETE FROM users WHERE id = $userId");
// Note: This also deletes from user_states due to CASCADE

// 2. Set State (This should trigger ensureUserExists and INSERT user, then INSERT state)
echo "Setting state for new user $userId...\n";
setUserState($userId, ['status' => 'testing', 'step' => 1]);

// 3. Verify User Exists
$stmtUser = $pdo->query("SELECT * FROM users WHERE id = $userId");
if ($stmtUser->fetch()) {
    echo "PASS: User $userId was created.\n";
} else {
    echo "FAIL: User $userId was NOT created.\n";
}

// 4. Verify State Exists
$state = getUserState($userId);
if ($state && $state['status'] === 'testing') {
    echo "PASS: State retrieved correctly: " . json_encode($state) . "\n";
} else {
    echo "FAIL: State retrieval failed.\n";
}

// 5. Update State
setUserState($userId, ['status' => 'testing_v2', 'step' => 2]);
$state2 = getUserState($userId);
if ($state2 && $state2['status'] === 'testing_v2') {
    echo "PASS: State updated correctly.\n";
} else {
    echo "FAIL: State update failed.\n";
}

echo "Done.\n";
?>
