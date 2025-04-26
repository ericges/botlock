<?php
// benchmark.php

// Configuration
$numBuckets = 1_000_000;
$maxCount   = 1_000;
$runs       = 5;

// 1) Generate random traffic_buckets (associative, unsorted)
$state['traffic_buckets'] = [];
for ($i = 0; $i < $numBuckets; $i++) {
    $state['traffic_buckets'][$i] = rand(0, $maxCount);
}

// 2) Precondition: sort buckets by key and build weight fn
\ksort($state['traffic_buckets']);
$amount = \count($state['traffic_buckets']);
$getWeight = static function (int $n) use ($amount): float {
    $x = ($amount - 1) - $n;
    return \pow(-0.05 * $x + 1.05, 8);
};

// 3) Reindex values
$buckets = \array_values($state['traffic_buckets']);

// 4) Warm-up
for ($i = 0; $i < 3; $i++) {
    // foreach
    $score = 0;
    foreach ($buckets as $j => $count) {
        $score += $count * $getWeight($j);
    }
    // array_reduce
    array_reduce(
        array_keys($buckets),
        static fn($carry, $j) => $carry + ($buckets[$j] * $getWeight($j)),
        0
    );
    // array_map + array_sum
    array_sum(
        array_map(
            static fn($count, $j) => $count * $getWeight($j),
            $buckets,
            array_keys($buckets)
        )
    );
}

// 5) Benchmark loop
$timeForeach  = 0.0;
$timeReduce   = 0.0;
$timeArraySum = 0.0;

for ($run = 1; $run <= $runs; $run++) {

    // array_reduce timing
    $start = microtime(true);
    $score2 = array_reduce(
        array_keys($buckets),
        static fn($carry, $j) => $carry + ($buckets[$j] * $getWeight($j)),
        0
    );
    $timeReduce += microtime(true) - $start;

    // foreach timing
    $start = microtime(true);
    $score1 = 0;
    foreach ($buckets as $j => $count) {
        $score1 += $count * $getWeight($j);
    }
    $timeForeach += microtime(true) - $start;

    // array_map + array_sum timing
    $start = microtime(true);
    $score3 = array_sum(
        array_map(
            static fn($count, $j) => $count * $getWeight($j),
            $buckets,
            array_keys($buckets)
        )
    );
    $timeArraySum += microtime(true) - $start;

    // sanity check
    if ($score1 !== $score2 || $score1 !== $score3) {
        fwrite(STDERR, "Mismatch on run {$run}: foreach={$score1}, reduce={$score2}, array_sum={$score3}\n");
    }
}

// 6) Report averages
printf("Average over %d runs:\n", $runs);
printf("  foreach     : %.6f sec\n", $timeForeach  / $runs);
printf("  array_reduce: %.6f sec\n", $timeReduce   / $runs);
printf("  array_sum   : %.6f sec\n", $timeArraySum / $runs);
