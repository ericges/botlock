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
    private int $level3ThresholdIndividual;
    private int $levelDecayGracePeriod;

    // Cache threat level per request to avoid repeated file reads
    private ?int $cachedGlobalThreatLevel = null;

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
        $this->level3ThresholdIndividual = $this->config->getLevel3ThresholdIndividual();
        $this->levelDecayGracePeriod = $this->config->getLevelDecayGracePeriod();

        if (!\is_dir($this->stateDir) && !@\mkdir($this->stateDir, 0775, true)) {
            throw new \RuntimeException("State directory '{$this->stateDir}' is not writable or cannot be created.");
        }

        if (!\is_writable($this->stateDir)) {
            throw new \RuntimeException("State directory '{$this->stateDir}' is not writable.");
        }
    }

    // --- File Locking Wrapper ---
    private function executeWithLock(string $filePath, callable $callback, string $mode = 'c+'): mixed
    {
        $startTime = microtime(true);
        $file = @fopen($filePath, $mode);
        if (!$file) { /* Log Error: cannot open state file */ return null; }

        $lockType = ($mode === 'r') ? LOCK_SH : LOCK_EX;

        $locked = false;
        while (microtime(true) - $startTime < (self::LOCK_TIMEOUT_MS / 1000)) {
            if (flock($file, $lockType | LOCK_NB)) { // Non-blocking attempt
                $locked = true;
                break;
            }
            usleep(5000); // Wait 5ms before retrying
        }

        if ($locked) {
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
        } else {
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
        // We need the session's fingerprint (subject)
        if (!isset($request->session)) {
            // Log error or handle - session should exist at this point if middleware order is correct
            return;
        }
        $fingerprint = $request->session->getSub(); // Assumes SessionMiddleware ran first

        // 1. Update Global State (only increments the current bucket)
        $this->incrementGlobalBucket();

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
        $currentLevel = 0; // Default safe level

        $updateLogic = function($fp) use (&$currentLevel) {
            $rawContent = stream_get_contents($fp);
            $state = json_decode($rawContent, true);

            if (!is_array($state) || !isset($state['traffic_buckets'])) {
                $state = ['current_level' => 0, 'level_last_changed' => time(), 'traffic_buckets' => []];
                // No need to calculate rate if state was just initialized
            }

            // --- Rate Calculation & Level Update ---
            $now = time();
            $rateStartTime = $now - ($this->globalRateWindowMin * 60);
            $currentRate = 0;
            $minBucketKey = floor($rateStartTime / 60);

            // Calculate rate from relevant buckets
            if (!empty($state['traffic_buckets'])) {
                foreach ($state['traffic_buckets'] as $key => $count) {
                    if ($key >= $minBucketKey && ($key * 60) >= $rateStartTime) {
                        $currentRate += $count;
                    }
                }
                // Don't prune here - separate occasional task or do it during incrementGlobalBucket
            }
            $requestsPerMinute = ($this->globalRateWindowMin > 0) ? ($currentRate / $this->globalRateWindowMin) : $currentRate;

            // Determine Level based on Rate
            $newLevel = 0;
            if ($requestsPerMinute >= $this->level3ThresholdGlobal) {
                $newLevel = 3;
            } elseif ($requestsPerMinute >= $this->level2ThresholdGlobal) {
                $newLevel = 2;
            } elseif ($requestsPerMinute >= $this->level1ThresholdGlobal) {
                $newLevel = 1;
            }

            // Apply Decay Logic
            $currentLevelInState = $state['current_level'] ?? 0;
            if ($newLevel < $currentLevelInState) {
                // Potential decrease - check grace period
                if (($now - ($state['level_last_changed'] ?? 0)) <= $this->levelDecayGracePeriod) {
                    // Still in grace period, keep the higher current level
                    $newLevel = $currentLevelInState;
                }
                // else: allow decrease as rate is low and grace period passed
            }

            // Check if level needs saving back to the file (only if changed)
            if ($newLevel !== $currentLevelInState) {
                // Need to reopen with exclusive lock to write
                // This simple read approach doesn't modify the file here.
                // Rate calculation should ideally happen probabilistically
                // within incrementGlobalBucket to avoid read+write lock escalation.
            }

            $currentLevel = $newLevel; // Return the calculated level
            return $state; // Return state for consistency, though not modified here
        };

        // Use shared lock if possible for reading global level
        $state = $this->executeWithLock($filePath, $updateLogic, 'r', true); // Try shared read first

        if ($state === null) {
            // Lock failed or file error - default to level 0? Or log and return higher level?
            // Returning 0 is safer against false positives but less protective on failure.
            // Let's default to 0 but log the error.
            // error_log("Botlock: Failed to read/lock global state file.");
            $this->cachedGlobalThreatLevel = 0;
        } else {
            $this->cachedGlobalThreatLevel = $state['current_level'] ?? 0; // Update from potentially modified state during increment
        }


        return $this->cachedGlobalThreatLevel;
    }

    /**
     * Increments the count for the current time bucket in the global state file.
     * Also performs probabilistic pruning and level recalculation/saving.
     */
    private function incrementGlobalBucket(): void
    {
        $filePath = $this->stateDir . \DIRECTORY_SEPARATOR . self::GLOBAL_STATE_FILE;

        $updateLogic = function($fp) {
            $rawContent = stream_get_contents($fp);
            $state = json_decode($rawContent, true);

            // Initialize if empty or invalid
            if (!is_array($state) || !isset($state['traffic_buckets'])) {
                $state = ['current_level' => 0, 'level_last_changed' => time(), 'traffic_buckets' => []];
            }

            // Increment current bucket
            $now = time();
            $currentBucketKey = floor($now / 60);
            $state['traffic_buckets'][$currentBucketKey] = ($state['traffic_buckets'][$currentBucketKey] ?? 0) + 1;

            // --- Probabilistic Pruning & Rate Calculation & Level Update (e.g., 1 in 50 times) ---
            if (rand(1, 50) === 1) {
                $rateStartTime = $now - ($this->globalRateWindowMin * 60);
                $currentRate = 0;
                $minBucketKey = floor($rateStartTime / 60);

                // Prune old buckets and calculate rate
                $bucketsToKeep = [];
                foreach ($state['traffic_buckets'] as $key => $count) {
                    if ($key >= $minBucketKey) {
                        $bucketsToKeep[$key] = $count;
                        if (($key * 60) >= $rateStartTime) { // Check if bucket start is within window
                            $currentRate += $count;
                        }
                    }
                }
                $state['traffic_buckets'] = $bucketsToKeep; // Assign pruned buckets back

                $requestsPerMinute = ($this->globalRateWindowMin > 0) ? ($currentRate / $this->globalRateWindowMin) : $currentRate;

                // Update Threat Level Logic (Copied from getGlobalThreatLevel - needs refactoring ideally)
                $oldLevel = $state['current_level'] ?? 0;
                $newLevel = 0; // Start from 0
                if ($requestsPerMinute >= $this->level3ThresholdGlobal) {
                    $newLevel = 3;
                } elseif ($requestsPerMinute >= $this->level2ThresholdGlobal) {
                    $newLevel = 2;
                } elseif ($requestsPerMinute >= $this->level1ThresholdGlobal) {
                    $newLevel = 1;
                }

                // Apply Decay Logic
                if ($newLevel < $oldLevel) {
                    if (($now - ($state['level_last_changed'] ?? 0)) <= $this->levelDecayGracePeriod) {
                        $newLevel = $oldLevel; // Keep higher level during grace period
                    }
                }

                // If level changed, update timestamp and level in state
                if ($newLevel !== $oldLevel) {
                    $state['current_level'] = $newLevel;
                    $state['level_last_changed'] = $now;
                    $this->cachedGlobalThreatLevel = $newLevel; // Update cache immediately
                }
            } // End probabilistic update

            // Write back the potentially modified state
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
        };

        // Use exclusive lock as we are modifying the file
        $result = $this->executeWithLock($filePath, $updateLogic, 'c+');

        if ($result === null) {
            // Log Error: Failed to update global state
        }
    }


    /**
     * Calculates and returns the request rate for an individual fingerprint.
     */
    public function getIndividualRate(string $fingerprint): int
    {
        if (empty($fingerprint)) return 0;

        $filePath = $this->getIndividualFilePath($fingerprint);
        $currentRate = 0;

        $readLogic = function($fp) use (&$currentRate) {
            $now = time();
            $windowStart = $now - $this->individualRateWindowSec;
            $count = 0;
            while (($line = fgets($fp)) !== false) {
                $ts = (int) trim($line);
                if ($ts >= $windowStart) {
                    $count++;
                }
            }
            $currentRate = $count;
            return true; // Indicate success
        };

        // Use shared lock for reading
        $success = $this->executeWithLock($filePath, $readLogic, 'r'); // Read-only, shared lock

        if ($success === null) {
            // Lock failed or file error
            // error_log("Botlock: Failed read/lock individual file for $fingerprint");
            return 0; // Default to 0 on failure
        }

        return $currentRate;
    }

    /**
     * Adds the current timestamp to the individual fingerprint file and prunes old ones.
     */
    private function updateIndividualTimestamps(string $fingerprint): void
    {
        if (empty($fingerprint)) return;

        $filePath = $this->getIndividualFilePath($fingerprint);
        $dirPath = dirname($filePath);

        if (!is_dir($dirPath)) {
            $old_umask = umask(0);
            @mkdir($dirPath, 0775, true);
            umask($old_umask);
        }

        $updateLogic = function($fp) {
            $now = time();
            $windowStart = $now - $this->individualRateWindowSec;
            $timestamps = [];
            while (($line = fgets($fp)) !== false) {
                $ts = (int) trim($line);
                if ($ts >= $windowStart) { // Keep only relevant timestamps
                    $timestamps[] = $ts;
                }
            }
            $timestamps[] = $now; // Add current request

            // Write back pruned + new data
            ftruncate($fp, 0);
            rewind($fp);
            foreach ($timestamps as $ts) {
                fwrite($fp, $ts . "\n");
            }
            return true; // Indicate success
        };

        // Exclusive lock needed for writing
        $success = $this->executeWithLock($filePath, $updateLogic, 'c+');

        if ($success === null) {
            // Log Error: Failed to update individual state for $fingerprint
        }
    }


    private function getIndividualFilePath(string $fingerprint): string
    {
        $hashDir = substr($fingerprint, 0, 2);
        return $this->stateDir . '/' . self::INDIVIDUAL_STATE_DIR . '/' . $hashDir . '/' . $fingerprint . '.tracker';
    }
}