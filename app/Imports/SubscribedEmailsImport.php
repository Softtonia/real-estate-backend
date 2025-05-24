<?php

namespace App\Imports;

use App\Models\SubscribedEmail;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class SubscribedEmailsImport implements ToModel, WithHeadingRow, WithChunkReading
{
    protected $importSummary = [
        'success' => 0,
        'duplicates' => 0,
        'invalidEmails' => 0,
        'updated' => 0,
    ];

    protected $errorLog = []; // Store error logs

    /**
     * Convert each row from the CSV/Excel file into a SubscribedEmail model.
     *
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Validate custom tag
        if (empty($row['custom_tag'])) {
            $this->logError($row, 'Missing Custom Tag');
            return null;
        }

        $validTags = ['New Subscribers', 'Promo Campaign'];
        if (!in_array($row['custom_tag'], $validTags)) {
            $this->logError($row, 'Invalid Custom Tag Value');
            return null;
        }

        // Validate email syntax
        if (empty($row['subscribe_email']) || !filter_var($row['subscribe_email'], FILTER_VALIDATE_EMAIL)) {
            $this->logError($row, 'Invalid Email Address');
            $this->importSummary['invalidEmails']++;
            return null; // Skip this row if the email is invalid
        }

        // Check if the email already exists
        $existingRecord = SubscribedEmail::where('subscribe_email', $row['subscribe_email'])->first();

        if ($existingRecord) {
            // Check if the existing record's data has changed
            $isUpdated = false;

            if ($existingRecord->is_subscribed != ($row['is_subscribed'] ?? $existingRecord->is_subscribed)) {
                $isUpdated = true;
            }

            if ($existingRecord->custom_tag != $row['custom_tag']) {
                $isUpdated = true;
            }

            if ($existingRecord->user_id != $this->getUserId($row['registerd_email_id'])) {
                $isUpdated = true;
            }

            if ($isUpdated) {
                // Increment updated counter
                $this->importSummary['updated']++;

                // Update the existing record
                $existingRecord->update([
                    'is_subscribed' => $row['is_subscribed'] ?? $existingRecord->is_subscribed,
                    'custom_tag' => $row['custom_tag'],
                    'user_id' => $this->getUserId($row['registerd_email_id']),
                ]);
                Log::info('Updated record for email: ' . $row['subscribe_email']);
            } else {
                // Increment duplicates counter if no changes were made
                $this->importSummary['duplicates']++;
            }

            return null; // No need to insert a new record
        }

        // Create a new record and increment the success counter
        $this->importSummary['success']++;

        return new SubscribedEmail([
            'subscribe_email' => $row['subscribe_email'],
            'is_subscribed' => $row['is_subscribed'],
            'user_id' => $this->getUserId($row['registerd_email_id']),
            'custom_tag' => $row['custom_tag'],
        ]);
    }

    /**
     * Log an error message with details.
     *
     * @param array $row
     * @param string $message
     */

    private function logError(array $row, string $message)
    {
        // Include the full row and the error message in the log
        $row['error_message'] = $message;  // Add the error message as part of the row
        $this->errorLog[] = $row;          // Store the full row in the error log
    }
    /**
     * Retrieve user ID based on the registered email.
     *
     * @param string $registeredEmail
     * @return int
     */
    private function getUserId($registeredEmail)
    {
        if (empty($registeredEmail)) {
            return 0; // Default for guest users
        }

        $user = \App\Models\User::where('email', $registeredEmail)->first();
        return $user ? $user->id : 'Guest';  // Return user ID if exists, else 0
    }

    /**
     * Set the number of rows to read at once.
     *
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000;  // Process 1000 rows at a time for efficiency
    }

    /**
     * Get the summary of the import process.
     *
     * @return array
     */
    public function getSummary()
    {
        return $this->importSummary;
    }

    /**
     * Generate an error log file for download.
     *
     * @return string
     */
    public function generateErrorLog()
    {
        if (empty($this->errorLog)) {
            return null;  // No errors to log
        }
    
        // Use Carbon to format the date and time
        $logFileName = 'error_log_' . Carbon::now()->format('Y-m-d_H-i-s') . '.csv';
    
        // Define the storage path (inside the storage directory)
        $localFilePath = storage_path('app/' . $logFileName);
    
        // Define the public path (public/uploads/error_log)
        $publicFilePath = public_path('uploads/error_log/' . $logFileName);
    
        // Ensure the directory for public path exists
        if (!File::exists(public_path('uploads/error_log'))) {
            File::makeDirectory(public_path('uploads/error_log'), 0755, true);
        }
    
        // Save the error log to the local file
        $file = fopen($localFilePath, 'w');
    
        // Add a header row that includes all possible keys from the error log
        $headerRow = array_keys($this->errorLog[0]);
        fputcsv($file, $headerRow);
    
        // Write each error row to the CSV file
        foreach ($this->errorLog as $error) {
            fputcsv($file, $error);
        }
    
        fclose($file);
    
        // Copy the file to the public directory
        copy($localFilePath, $publicFilePath);
    
        // Return the relative path for public access
        return 'uploads/error_log/' . $logFileName;
    }
    
}


