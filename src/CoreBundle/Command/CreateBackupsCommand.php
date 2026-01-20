<?php

namespace Chamilo\CoreBundle\Command;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Repository\Node\CourseRepository;
use Chamilo\CourseBundle\Component\CourseCopy\CourseBuilder;
use Chamilo\CourseBundle\Component\CourseCopy\Moodle\Builder\MoodleExport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:create-backups')]
/**
 * CreateBackupsCommand
 *
 * Symfony Console command to create Moodle-format backups (.mbz) of Chamilo courses.
 *
 * Main features:
 * - Backup all active courses, or a specific course by code.
 * - Store backups in a configurable directory (default: /var/backups/chamilo).
 * - Archive daily backups into zip files and keep only the 30 most recent archives.
 * - Log all actions and errors to a per-run log file in var/log.
 * - Clean up old log files, keeping only the 31 most recent.
 * - Handle Chamilo legacy environment initialization for backup compatibility.
 * - Skip courses with missing files or data integrity issues, logging warnings.
 *
 * Usage example:
 *   sudo mkdir -p /var/backups/chamilo
 *   sudo chown www-data:www-data /var/backups/chamilo
 *   sudo chmod 750 /var/backups/chamilo
 *   sudo -u www-data php bin/console app:create-backups
 *
 * Options:
 *   --backup-dir=PATH   Set backup directory (default: /var/backups/chamilo)
 *   --course-code=CODE  Backup only the specified course
 *
 * Log file:
 *   Each run creates a log file in var/log/backup_YYYY-mm-dd_HH-MM-SS.log
 *
 * Retention:
 *   After each run, all .mbz files are zipped and only the 30 most recent zip files are kept.
 * 
 */
class CreateBackupsCommand extends Command
{
    private CourseRepository $courseRepository;
    private bool $legacyInitialized = false;
    private string $logFile;

    public function __construct(CourseRepository $courseRepository)
    {
        parent::__construct();
        $this->courseRepository = $courseRepository;
        
        // Initialize log file path with timestamp for per-run logs
        $logDir = '/var/www/chamilo/var/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/backup_' . date('Y-m-d_H-i-s') . '.log';
    }

    protected function configure(): void
    {
        $this->setDescription('Create backups of all active courses')
            ->addOption('backup-dir', null, InputOption::VALUE_REQUIRED, 'Backup directory path', '/var/backups/chamilo')
            ->addOption('course-code', null, InputOption::VALUE_REQUIRED, 'Backup only specific course by code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Log backup start
        $this->log('=== Backup started at ' . date('Y-m-d H:i:s') . ' ===');

        // Clean up old log files (keep max 31)
        $this->cleanupOldLogs();

        $backupDir = $input->getOption('backup-dir');
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                $message = 'Failed to create backup directory: ' . $backupDir;
                $io->error($message . '. Please check permissions or specify a different directory with --backup-dir');
                $this->log('ERROR: ' . $message);
                return Command::FAILURE;
            }
        }
        
        // Ensure www-data can write to the backup directory
        if (!is_writable($backupDir)) {
            $message = 'Backup directory is not writable: ' . $backupDir . '. Please check permissions.';
            $io->error($message);
            $this->log('ERROR: ' . $message);
            return Command::FAILURE;
        }
        
        $this->log('Using backup directory: ' . $backupDir);
        
        // Clean up old backup files
        $this->cleanupOldBackups($backupDir);

        $courses = $this->courseRepository->findBy([], null, null, null);
        $timestamp = date('Y-m-d_H-i-s');

        // Filter by course code if specified
        $courseCodeFilter = $input->getOption('course-code');
        if ($courseCodeFilter) {
            $courses = array_filter($courses, fn($course) => $course->getCode() === $courseCodeFilter);
        }

        $activeCourses = array_filter($courses, fn($course) => $course->isActive());
        $statusMsg = sprintf('Found %d active courses to backup (out of %d total)', count($activeCourses), count($courses));
        $this->log($statusMsg);

        $successCount = 0;
        $failCount = 0;

        foreach ($courses as $course) {
            // Skip inactive courses
            if (!$course->isActive()) {
                continue;
            }
            
            try {
                $this->createCourseBackup($course, $backupDir, $timestamp);
                $successMsg = sprintf('Backup created for course: %s', $course->getCode());
                $io->success($successMsg);
                $this->log('SUCCESS: ' . $successMsg);
                $successCount++;
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                // Skip courses with missing files or data integrity issues
                if (strpos($errorMsg, 'Source file not found') !== false || 
                    strpos($errorMsg, 'Undefined array key') !== false ||
                    strpos($errorMsg, 'ERROR source not found') !== false) {
                    $warningMsg = sprintf('Skipped course %s (missing files): %s', $course->getCode(), substr($errorMsg, 0, 100) . '...');
                    $io->warning($warningMsg);
                    $this->log('WARNING: ' . $warningMsg);
                    $this->log('FULL ERROR: ' . $errorMsg);
                } else {
                    $failMsg = sprintf('Failed to backup course %s: %s', $course->getCode(), $errorMsg);
                    $io->error($failMsg);
                    $this->log('ERROR: ' . $failMsg);
                }
                $failCount++;
            }
        }

        $finalMsg = sprintf('Backup completed - Success: %d, Failed: %d', $successCount, $failCount);
        $this->log($finalMsg);
        $this->log('=== Backup finished at ' . date('Y-m-d H:i:s') . ' ===');
        $this->log('Log file: ' . $this->logFile);

        
        return Command::SUCCESS;
    }

    private function initializeLegacyEnvironment(): void
    {
        if ($this->legacyInitialized) {
            return;
        }

        // Save current working directory
        $originalCwd = getcwd();
        
        try {
            // Change to the public directory where global.inc.php expects to be called from
            chdir(__DIR__ . '/../../../public/main');
            
            // Include the global.inc.php to initialize Chamilo's legacy environment
            require_once __DIR__ . '/../../../public/main/inc/global.inc.php';
            
            $this->legacyInitialized = true;
            
        } finally {
            // Restore original working directory
            chdir($originalCwd);
        }
    }

    private function createCourseBackup(Course $course, string $backupDir, string $timestamp): void
    {
        // Initialize legacy environment once
        $this->initializeLegacyEnvironment();
        
        $courseCode = $course->getCode();
        
        // Get course info using legacy API
        $courseInfo = api_get_course_info($courseCode);
        if (!$courseInfo) {
            throw new \Exception('Could not get course info for: ' . $courseCode);
        }
        
        \ChamiloSession::write('_course', $courseInfo);
        \ChamiloSession::write('_cid', $courseInfo['real_id']);
        
        try {
            // Build the course object using CourseBuilder (same as MoodleExport does)
            $courseBuilder = new CourseBuilder('complete', $courseInfo);
            $course = $courseBuilder->build(0, $courseInfo['code']);
            
            if (!$course) {
                throw new \Exception('Failed to build course object for MoodleExport');
            }
            
            // Create MoodleExport object
            $moodleExport = new MoodleExport($course, false); // false = not selection mode
            
            // Create temporary export directory identifier
            $tempExportDir = 'backup_' . $courseCode . '_' . uniqid();
            
            // Suppress non-fatal warnings during export, but log them for debugging
            $self = $this;
            $oldErrorHandler = set_error_handler(function($errno, $errstr, $errfile = '', $errline = 0) use ($self, $courseCode) {
                // Only convert fatal errors to exceptions
                if ($errno === E_ERROR || $errno === E_PARSE) {
                    throw new \Exception($errstr);
                }
                // Log warnings and notices for debugging
                $level = ($errno === E_WARNING) ? 'WARNING' : (($errno === E_NOTICE) ? 'NOTICE' : 'INFO');
                $msg = sprintf('%s: [%s] %s in %s on line %d (course: %s)', $level, $errno, $errstr, $errfile, $errline, $courseCode);
                $self->log($msg);
                return true;
            });
            
            try {
                // Use MoodleExport::export() to create the .mbz file
                $exportedFile = $moodleExport->export($courseCode, $tempExportDir, 4);
            } finally {
                // Restore original error handler
                if ($oldErrorHandler) {
                    set_error_handler($oldErrorHandler);
                } else {
                    restore_error_handler();
                }
            }
            
            if (!file_exists($exportedFile)) {
                throw new \Exception('MoodleExport did not create the expected .mbz file at: ' . $exportedFile);
            }
            
            // Move the .mbz file to the backup directory
            $backupFilename = $courseCode . '_backup_' . $timestamp . '.mbz';
            $backupPath = $backupDir . '/' . $backupFilename;
            
            if (!copy($exportedFile, $backupPath)) {
                throw new \Exception('Failed to copy .mbz file to backup directory');
            }
            
            // Clean up the original .mbz file
            if (file_exists($exportedFile)) {
                unlink($exportedFile);
            }
            
        } finally {
            // Clean up course context
            unset($_SESSION['_course']);
            unset($_SESSION['_cid']);
            unset($GLOBALS['_course']);
            \ChamiloSession::erase('_course');
            \ChamiloSession::erase('_cid');
        }
    }
    
    /**
     * Retention policy: keep last 30 backup zip files (one per day), delete older ones.
     * After each backup run, zip all .mbz files for the day into a single zip, then remove the .mbz files.
     * Only keep the 30 most recent zip files (by date).
     */
    private function cleanupOldBackups(string $backupDir): void
    {
        // 1. Zip all .mbz files for this run into a single zip file 
        $now = date('Y-m-d_H-i-s');
        $mbzFiles = [];
        if (is_dir($backupDir)) {
            $dirIterator = new \DirectoryIterator($backupDir);
            foreach ($dirIterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'mbz') {
                    $mbzFiles[] = $fileinfo->getPathname();
                }
            }
        }
        $zipFile = $backupDir . '/backup_' . $now . '.zip';

        if (!empty($mbzFiles)) {
            $this->log(sprintf('Archiving %d backup file(s) into %s...', count($mbzFiles), basename($zipFile)));
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
                foreach ($mbzFiles as $file) {
                    $zip->addFile($file, basename($file));
                }
                $zip->close();
                // Remove the .mbz files after archiving
                foreach ($mbzFiles as $file) {
                    if (unlink($file)) {
                        $this->log('Deleted: ' . basename($file));
                    } else {
                        $this->log('WARNING: Failed to delete: ' . basename($file));
                    }
                }
            } else {
                $this->log('WARNING: Failed to create zip archive: ' . basename($zipFile));
            }
        }

        // 2. Retention: keep only the 30 most recent zip files
        $zipFiles = [];
        if (is_dir($backupDir)) {
            $dirIterator = new \DirectoryIterator($backupDir);
            foreach ($dirIterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'zip' && strpos($fileinfo->getFilename(), 'backup_') === 0) {
                    $zipFiles[] = $fileinfo->getPathname();
                }
            }
        }

        // Sort by most recent first
        usort($zipFiles, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        if (count($zipFiles) > 30) {
            $toDelete = array_slice($zipFiles, 30);
            foreach ($toDelete as $file) {
                if (unlink($file)) {
                    $this->log('Deleted old backup archive: ' . basename($file));
                } else {
                    $this->log('WARNING: Failed to delete old backup archive: ' . basename($file));
                }
            }
        }
    }
    
    /**
     * Log a message to the backup log file with timestamp
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Retention policy: keep last 31 log files (including current run), delete older ones.
     * Only applies to files matching backup_*.log in var/log.
     */
    private function cleanupOldLogs(): void
    {
        $logDir = dirname($this->logFile);
        $logFiles = [];
        if (is_dir($logDir)) {
            $dirIterator = new \DirectoryIterator($logDir);
            foreach ($dirIterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'log' && strpos($fileinfo->getFilename(), 'backup_') === 0) {
                    $logFiles[] = $fileinfo->getPathname();
                }
            }
        }
        
        // Sort by most recent first
        usort($logFiles, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        if (count($logFiles) > 31) {
            $toDelete = array_slice($logFiles, 31);
            foreach ($toDelete as $file) {
                @unlink($file);
            }
        }
    }
        
}