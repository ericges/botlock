<?php declare(strict_types=1);

namespace GES\Botlock;

use GES\Botlock\Http\Request;
use Throwable;

class RateLimiter
{
    // Constants for thresholds, windows, etc. - fetch from Config
    private const GLOBAL_STATE_FILE = 'global_state.json';
    private const INDIVIDUAL_STATE_DIR = 'ua';
    private const LOCK_TIMEOUT_MS = 100; // Max time to wait for a lock

    private string $stateDir;
    private int $globalRateWindowMin;
    private int $individualRateWindowSec;
    private int $level1ThresholdGlobal;
    private int $level2ThresholdGlobal;
    private int $level3ThresholdGlobal;
    private int $level1ThresholdIndividual;
    private int $level2ThresholdIndividual;
    private int $level3ThresholdIndividual;
    private int $levelDecayGracePeriod;

    private ?int $cachedGlobalThreatLevel = null;
    private array $cachedIndividualThreatLevels = [];
    private array $cachedIndividualRates = [];

    public function __construct(private readonly Config $config)
    {
        // Fetch config values
        $this->stateDir = $this->config->getStateDir(); // Needs to be added to Config
        $this->globalRateWindowMin = $this->config->getGlobalRateWindowMin(); // Add getter
        $this->individualRateWindowSec = $this->config->getIndividualRateWindowSec(); // Add getter
        // ... (load all thresholds and grace period from config) ...
        $this->level1ThresholdGlobal = $this->config->getLevel1ThresholdGlobal(); // Add getter etc.
        $this->level2ThresholdGlobal = $this->config->getLevel2ThresholdGlobal();
        $this->level3ThresholdGlobal = $this->config->getLevel3ThresholdGlobal();
        $this->level1ThresholdIndividual = $this->config->getLevel1ThresholdIndividual();
        $this->level2ThresholdIndividual = $this->config->getLevel2ThresholdIndividual();
        $this->level3ThresholdIndividual = $this->config->getLevel3ThresholdIndividual();
        $this->levelDecayGracePeriod = $this->config->getLevelDecayGracePeriod();

        if (!\is_dir($this->stateDir) && !@\mkdir($this->stateDir, 0775, true)) {
            throw new \RuntimeException("State directory '{$this->stateDir}' is not writable or cannot be created.");
        }

        if (!\is_writable($this->stateDir)) {
            throw new \RuntimeException("State directory '{$this->stateDir}' is not writable.");
        }
    }

    /**
     * File locking mechanism to ensure thread-safe access.
     */
    private function executeWithLock(string $filePath, callable $callback, string $mode = 'c+'): mixed
    {
        if (!$file = @\fopen($filePath, $mode)) {
            /* Log Error: cannot open state file */
            return null;
        }

        $startTime = \microtime(true);
        $lockType = ($mode === 'r') ? \LOCK_SH : \LOCK_EX;
        $locked = false;

        while (\microtime(true) - $startTime < (self::LOCK_TIMEOUT_MS / 1000))
        {
            if (\flock($file, $lockType | \LOCK_NB))
                // Non-blocking attempt
            {
                $locked = true;
                break;
            }

            \usleep(5000); // Wait 5ms before retrying
        }

        if ($locked)
        {
            try {
                $result = $callback($file);
                if ($mode !== 'r') { // Ensure data written on write modes
                    fflush($file);
                }
            } catch (Throwable $e) {
                /* Log Error: Callback failed */
                $result = null; // Indicate failure
            } finally {
                flock($file, LOCK_UN); // Release lock
            }
        }
        else
        {
            /* Log Warning: could not get lock, maybe return default state or skip? */
            $result = null; // Indicate failure
            // Consider alternative action on lock failure - e.g., temporary higher threat?
        }

        fclose($file);
        return $result;
    }


    /**
     * Records the current request for both global and individual tracking.
     */
    public function recordRequest(Request $request): void
    {
        // 1. Update Global State (only increments the current bucket)
        $this->incrementGlobalBucket();

        if (!$fingerprint = $request->fingerprint) {
            return;
        }

        // 2. Update Individual State
        $this->updateIndividualTimestamps($fingerprint);
    }

    /**
     * Calculates and returns the current global threat level.
     * Uses per-request caching.
     */
    public function getGlobalThreatLevel(): int
    {
        if ($this->cachedGlobalThreatLevel !== null) {
            return $this->cachedGlobalThreatLevel;
        }

        $filePath = $this->stateDir . '/' . self::GLOBAL_STATE_FILE;

        // Use shared lock if possible for reading global level
        $state = $this->executeWithLock($filePath, $this->getGlobalThreatLevelLogic(...), 'r', true);

        if ($state === null) {
            // Lock failed or file error - default to level 0? Or log and return higher level?
            // Returning 0 is safer against false positives but less protective on failure.
            // Let's default to 0 but log the error.
            // error_log("Botlock: Failed to read/lock global state file.");
            $this->cachedGlobalThreatLevel = 1;
        } else {
            // Update from potentially modified state during increment
            $this->cachedGlobalThreatLevel = $state['current_level'] ?? 0;
        }

        return $this->cachedGlobalThreatLevel;
    }

    private function getGlobalThreatLevelLogic($file): array
    {
        $rawContent = \stream_get_contents($file);
        $state = \json_decode($rawContent, true);
        $now = \time();

        if (!\is_array($state) || !isset($state['traffic_buckets']))
        {
            return ['current_level' => 0, 'level_last_changed' => $now, 'traffic_buckets' => []];
            // No need to calculate rate if state was just initialized
        }

        // --- Rate Calculation & Level Update ---
        $currentRate = 0;

        // Calculate rate from relevant buckets
        if (!empty($state['traffic_buckets']))
        {
            $rateStartTime = $now - ($this->globalRateWindowMin * 60);
            $minBucketKey = \floor($rateStartTime / 60);

            foreach ($state['traffic_buckets'] as $key => $count)
            {
                $key = (int) $key;

                if ($key >= $minBucketKey && ($key * 60) >= $rateStartTime)
                {
                    $currentRate += $count;
                }
            }
            // Don't prune here - separate occasional task or do it during incrementGlobalBucket
        }

        [$oldLevel, $newLevel] = $this->calcOldAndNewLevelsGlobal($currentRate, $state);

        // Check if level needs saving back to the file (only if changed)
        if ($newLevel !== $oldLevel) {
            // Need to reopen with exclusive lock to write
            // This simple read approach doesn't modify the file here.
            // Rate calculation should ideally happen probabilistically
            // within incrementGlobalBucket to avoid read+write lock escalation.
        }

        return $state; // Return the calculated level and state
    }

    /**
     * Increments the count for the current time bucket in the global state file.
     * Also performs probabilistic pruning and level recalculation/saving.
     */
    private function incrementGlobalBucket(): void
    {
        $filePath = $this->stateDir . \DIRECTORY_SEPARATOR . self::GLOBAL_STATE_FILE;
        // Use exclusive lock as we are modifying the file
        $this->executeWithLock($filePath, $this->incrementGlobalBucketLogic(...));
    }

    private function incrementGlobalBucketLogic($file): void
    {
        $rawContent = \stream_get_contents($file);
        $state = \json_decode($rawContent, true);
        $now = \time();

        // Initialize if empty or invalid
        if (!\is_array($state) || !isset($state['traffic_buckets'])) {
            $state = ['current_level' => 0, 'level_last_changed' => $now, 'traffic_buckets' => []];
        }

        // Increment current bucket
        $currentBucketKey = \floor($now / 60);
        $state['traffic_buckets'][$currentBucketKey] = ($state['traffic_buckets'][$currentBucketKey] ?? 0) + 1;

        if (\rand(1, 30) === 1)
            // Probabilistic Pruning & Rate Calculation & Level Update (e.g., 1 in 50 times)
        {
            $this->updateGlobalState($state);
        }

        // Write back the potentially modified state
        \ftruncate($file, 0);
        \rewind($file);
        \fwrite($file, \json_encode($state));
    }

    public function updateGlobalState(array &$state): void
    {
        $now = \time();

        $rateStartTime = $now - ($this->globalRateWindowMin * 60);
        $minBucketKey = \floor($rateStartTime / 60);

        $currentRate = 0;
        $bucketsToKeep = [];

        foreach ($state['traffic_buckets'] as $key => $count)
            // Prune old buckets and calculate rate
        {
            $key = (int) $key;

            if ($key >= $minBucketKey)
            {
                $bucketsToKeep[$key] = $count;
                if (($key * 60) >= $rateStartTime) { // Check if bucket start is within window
                    $currentRate += $count;
                }
            }
        }

        $state['traffic_buckets'] = $bucketsToKeep; // Assign pruned buckets back

        [$oldLevel, $newLevel] = $this->calcOldAndNewLevelsGlobal($currentRate, $state);

        if ($newLevel !== $oldLevel)
            // If level changed, update timestamp and level in state
        {
            $state['current_level'] = $newLevel;
            $state['level_last_changed'] = $now;
            $this->cachedGlobalThreatLevel = $newLevel; // Update cache immediately
        }
    }

    private function calcOldAndNewLevelsGlobal(int $currentRate, array $state): array
    {
        $requestsPerMinute = $currentRate / \max(1, $this->globalRateWindowMin);

        // Update Threat Level Logic (Copied from getGlobalThreatLevel - needs refactoring ideally)
        $newLevel = match (true) {
            $requestsPerMinute >= $this->level3ThresholdGlobal => 3,
            $requestsPerMinute >= $this->level2ThresholdGlobal => 2,
            $requestsPerMinute >= $this->level1ThresholdGlobal => 1,
            default => 0,
        };

        $oldLevel = $state['current_level'] ?? 0;
        if ($newLevel < $oldLevel && (\time() - ($state['level_last_changed'] ?? 0)) <= $this->levelDecayGracePeriod)
            // Apply decay logic but keep higher level during grace period
        {
            $newLevel = $oldLevel;
        }

        return [$oldLevel, $newLevel];
    }

    /**
     * Calculates and returns the request rate for an individual fingerprint.
     */
    public function getIndividualRate(string $fingerprint): int
    {
        if (!$fingerprint) {
            return 0;
        }

        if (isset($this->cachedIndividualRates[$fingerprint])) {
            return $this->cachedIndividualRates[$fingerprint];
        }

        $filePath = $this->getIndividualFilePath($fingerprint);

        // Use shared lock for reading
        $individualRate = (int) $this->executeWithLock($filePath, $this->getIndividualRateLogic(...), 'r');

        return $this->cachedIndividualRates[$fingerprint] = $individualRate;
    }

    private function getIndividualRateLogic($file): int
    {
        $windowStart = \time() - $this->individualRateWindowSec;
        $count = 0;

        while (($line = fgets($file)) !== false)
        {
            $ts = (int) trim($line);

            if ($ts >= $windowStart) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculates and returns the current threat level for an individual fingerprint
     * based on their request rate within the individual time window.
     * Uses per-request caching.
     *
     * @param string $fingerprint The client fingerprint.
     * @return int The calculated threat level (0, 1, or 3 based on configured thresholds).
     */
    public function getIndividualThreatLevel(string $fingerprint): int
    {
        if (empty($fingerprint)) {
            return 1;
        }

        if (isset($this->cachedIndividualThreatLevels[$fingerprint])) {
            return $this->cachedIndividualThreatLevels[$fingerprint];
        }

        // 1. Get the current individual rate (count of requests within the window)
        $individualRate = $this->getIndividualRate($fingerprint);

        // 2. Determine the threat level based on the rate and individual thresholds.
        // Note: $individualRate is the total count within $individualRateWindowSec.
        // Ensure your thresholds ($levelXThresholdIndividual) are set based on this window.
        $level = match (true) {
            $individualRate >= $this->level3ThresholdIndividual => 3,
            $individualRate >= $this->level2ThresholdIndividual => 2,
            $individualRate >= $this->level1ThresholdIndividual => 1,
            default => 0,
        };

        // Note: No decay logic is implemented here as the individual state
        // file format doesn't store previous level or change timestamps.
        // Adding decay would require changing the individual state storage format.

        $this->cachedIndividualThreatLevels[$fingerprint] = $level;

        return $level;
    }


    /**
     * Adds the current timestamp to the individual fingerprint file and prunes old ones.
     */
    private function updateIndividualTimestamps(string $fingerprint): void
    {
        if (empty($fingerprint)) return;

        $filePath = $this->getIndividualFilePath($fingerprint);
        $dirPath = \dirname($filePath);

        if (!\is_dir($dirPath))
        {
            $oldUmask = \umask(0);
            @\mkdir($dirPath, 0775, true);
            \umask($oldUmask);
        }

        // Exclusive lock needed for writing
        $this->executeWithLock($filePath, $this->updateIndividualTimestampsLogic(...));
    }

    private function updateIndividualTimestampsLogic($file): void
    {
        $now = \time();
        $windowStart = $now - $this->individualRateWindowSec;
        $timestamps = [];

        while (($line = \fgets($file)) !== false)
        {
            $ts = (int) \trim($line);

            if ($ts >= $windowStart) { // Keep only relevant timestamps
                $timestamps[] = $ts;
            }
        }

        $timestamps[] = $now; // Add current request

        // Write back pruned + new data
        \ftruncate($file, 0);
        \rewind($file);

        foreach ($timestamps as $ts) {
            \fwrite($file, $ts . "\n");
        }
    }

    private function getIndividualFilePath(string $fingerprint): string
    {
        $hashDir = substr($fingerprint, 0, 2);
        return $this->stateDir . '/' . self::INDIVIDUAL_STATE_DIR . '/' . $hashDir . '/' . $fingerprint . '.json';
    }
}