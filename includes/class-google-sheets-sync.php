<?php
defined('ABSPATH') || exit;

use Avpvh\Vendor\Google\Client;
use Avpvh\Vendor\Google\Service\Sheets;

class AVPVH_Google_Sheets_Sync {

    private Client $client;
    private Sheets $sheets;
    private string $spreadsheet_id;
    private string $sheet_name = 'Form_Responses';

    public function __construct() {
        $this->spreadsheet_id = get_option('avpvh_google_sheets_id', '');
        if (!$this->spreadsheet_id) {
            throw new Exception('Google Sheets ID not configured. Please set avpvh_google_sheets_id option.');
        }
    }

    /**
     * Initialize Google client and authenticate.
     */
    private function init_client(): void {
        if (isset($this->client)) {
            return;
        }

        $this->client = new Client();
        $this->client->setApplicationName('AVP Philips van Horne');
        $this->client->setScopes([
            Sheets::SPREADSHEETS,
            Sheets::DRIVE,
        ]);

        // Load stored token
        $token = get_option('avpvh_google_sheets_token');
        if ($token) {
            $this->client->setAccessToken(json_decode($token, true));
        }

        // Refresh token if needed
        if ($this->client->isAccessTokenExpired()) {
            $refresh_token = $this->client->getRefreshToken();
            if ($refresh_token) {
                $this->client->fetchAccessTokenWithRefreshToken($refresh_token);
                update_option('avpvh_google_sheets_token', json_encode($this->client->getAccessToken()));
            }
        }

        $this->sheets = new Sheets($this->client);
    }

    /**
     * Sync registrations from Google Sheet to WordPress database.
     * Returns array with counts: updated, created, conflicts.
     */
    public function sync_from_sheet(int $camp_id, int $year): array {
        $this->init_client();

        $result = [
            'created' => 0,
            'updated' => 0,
            'conflicts' => 0,
            'errors' => [],
        ];

        try {
            // Fetch all data from sheet
            $response = $this->sheets->spreadsheets_values->get(
                $this->spreadsheet_id,
                "{$this->sheet_name}!A:Z"
            );

            $values = $response->getValues() ?: [];
            if (empty($values)) {
                return $result;
            }

            // First row is headers, skip it
            array_shift($values);

            foreach ($values as $row_index => $row) {
                try {
                    $parsed = $this->parse_sheet_row($row);
                    if (!$parsed) {
                        continue; // Skip invalid rows
                    }

                    $registration_id = $this->upsert_registration_from_sheet(
                        $parsed,
                        $camp_id,
                        $year,
                        $row_index + 2 // +2 because we skip header and indices start at 0
                    );

                    if ($parsed['has_conflict']) {
                        $result['conflicts']++;
                    } elseif ($parsed['was_new']) {
                        $result['created']++;
                    } else {
                        $result['updated']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = "Row " . ($row_index + 2) . ": " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $result['errors'][] = "Failed to fetch sheet data: " . $e->getMessage();
        }

        return $result;
    }

    /**
     * Sync registrations from WordPress database to Google Sheet.
     * Returns array with counts: pushed, conflicts.
     */
    public function sync_to_sheet(int $camp_id, int $year): array {
        $this->init_client();

        $result = [
            'pushed' => 0,
            'conflicts' => 0,
            'errors' => [],
        ];

        // Get pending registrations
        $registrations = AVPVH_Registration_DB::get_registrations_for_camp($camp_id, $year);

        foreach ($registrations as $registration) {
            // Only push if pending_push or newer than last sync
            if ($registration->sync_status !== 'pending_push' && !$this->is_newer_than_sync($registration)) {
                continue;
            }

            try {
                $this->push_registration_to_sheet($registration);
                AVPVH_Registration_DB::update_sync_status($registration->id, 'synced');
                $result['pushed']++;
            } catch (Exception $e) {
                $result['errors'][] = "Registration {$registration->id}: " . $e->getMessage();
                AVPVH_Registration_DB::update_sync_status($registration->id, 'pending_push');
            }
        }

        return $result;
    }

    /**
     * Parse a Google Sheet row into registration data.
     * Returns array with registration info or null if invalid.
     */
    private function parse_sheet_row(array $row): ?array {
        if (empty($row[0])) {
            return null; // Skip empty rows
        }

        // Column mapping (based on the Google Form structure)
        // This is simplified; actual sheet has many date columns
        $parsed = [
            'timestamp' => $row[0] ?? '',
            'email' => $row[1] ?? '',
            'first_name' => $row[2] ?? '',
            'person2_name' => $row[3] ?? '',
            'person3_name' => $row[4] ?? '',
            'phone' => $row[15] ?? '',
            'country' => $row[11] ?? 'Nederland',
            'postal_code' => $row[13] ?? '',
            'house_number' => $row[14] ?? '',
            'emergency_contact' => $row[16] ?? '',
            'emergency_phone' => $row[17] ?? '',
            'food_allergies_p1' => $row[9] ?? '',
            'food_allergies_p2' => $row[10] ?? '',
            'food_allergies_p3' => $row[68] ?? '',
            'notes' => $row[8] ?? '',
            'has_conflict' => false,
            'was_new' => false,
        ];

        // Parse attendance columns (very simplified - actual sheet has 16 date columns per person + nawacht)
        // For now, we'll add placeholders
        $parsed['attendance'] = [];

        return $parsed;
    }

    /**
     * Insert or update a registration from parsed sheet data.
     */
    private function upsert_registration_from_sheet(array $parsed, int $camp_id, int $year, int $google_row_id): int {
        // Look up member by email
        $member = AVPVH_DB::get_member_by_email($parsed['email']);
        if (!$member) {
            throw new Exception("Member not found with email: {$parsed['email']}");
        }

        // Get or create registration
        $registration = AVPVH_Registration_DB::get_registration_by_member_camp($member->id, $camp_id, $year);

        $is_new = !$registration;

        // Detect conflicts with existing registration
        $has_conflict = false;
        if ($registration && $registration->last_sync_timestamp) {
            $sheet_time = strtotime($parsed['timestamp']);
            $last_sync = strtotime($registration->last_sync_timestamp);

            // If sheet timestamp is older than last sync, there's a conflict
            if ($sheet_time < $last_sync) {
                $has_conflict = true;
                // Log conflict instead of overwriting
                AVPVH_Registration_DB::log_conflict(
                    $registration->id,
                    'sheet_vs_wp',
                    date('Y-m-d H:i:s', $last_sync),
                    $parsed['timestamp']
                );
            }
        }

        // Save or update registration
        $reg_id = AVPVH_Registration_DB::save_registration(
            $member->id,
            $camp_id,
            $year,
            $has_conflict ? 'conflict' : 'synced',
            $google_row_id
        );

        if (!$is_new || !$has_conflict) {
            // Save participant data
            AVPVH_Registration_DB::save_participant($reg_id, 1, $parsed['first_name'], $parsed['food_allergies_p1']);
            if (!empty($parsed['person2_name'])) {
                AVPVH_Registration_DB::save_participant($reg_id, 2, $parsed['person2_name'], $parsed['food_allergies_p2']);
            }
            if (!empty($parsed['person3_name'])) {
                AVPVH_Registration_DB::save_participant($reg_id, 3, $parsed['person3_name'], $parsed['food_allergies_p3']);
            }

            // Save notes
            if (!empty($parsed['notes'])) {
                AVPVH_Registration_DB::save_notes($reg_id, $parsed['notes']);
            }
        }

        return $reg_id;
    }

    /**
     * Push a registration to Google Sheet.
     */
    public function push_registration_to_sheet(object $registration): void {
        // Implementation depends on whether we're appending new row or updating existing
        if ($registration->google_row_id) {
            $this->update_sheet_row($registration);
        } else {
            $this->append_sheet_row($registration);
        }
    }

    /**
     * Append a new row to Google Sheet.
     */
    private function append_sheet_row(object $registration): void {
        $this->init_client();

        $member = AVPVH_DB::get_member($registration->member_id);
        if (!$member) {
            throw new Exception("Member not found: {$registration->member_id}");
        }

        $participants = AVPVH_Registration_DB::get_participants($registration->id);
        $notes_obj = AVPVH_Registration_DB::get_notes($registration->id);

        // Build row values (simplified - actual sheet has many date columns)
        $row = [
            current_time('Y-m-d H:i:s'), // Timestamp
            $member->email, // Email
            $member->first_name, // First name Person 1
            $participants[1]->first_name ?? '', // Person 2
            $participants[2]->first_name ?? '', // Person 3
            // ... many more columns ...
            $member->phone,
            $member->emergency_contact,
            $member->mobile,
            $notes_obj->notes ?? '',
        ];

        $body = new Sheets\ValueRange([
            'values' => [$row],
        ]);

        try {
            $this->sheets->spreadsheets_values->append(
                $this->spreadsheet_id,
                "{$this->sheet_name}!A:Z",
                $body,
                ['valueInputOption' => 'USER_ENTERED']
            );
        } catch (Exception $e) {
            throw new Exception("Failed to append row to sheet: " . $e->getMessage());
        }
    }

    /**
     * Update an existing row in Google Sheet.
     */
    private function update_sheet_row(object $registration): void {
        $this->init_client();

        // Implementation similar to append_sheet_row but using update instead
        // For now, this is a placeholder
    }

    /**
     * Check if registration was updated after last sync.
     */
    private function is_newer_than_sync(object $registration): bool {
        if (!$registration->last_sync_timestamp) {
            return true;
        }

        return strtotime($registration->updated_at) > strtotime($registration->last_sync_timestamp);
    }

    /**
     * Test Google Sheets access by reading first row.
     */
    public function test_access(): bool {
        try {
            $this->init_client();

            $response = $this->sheets->spreadsheets_values->get(
                $this->spreadsheet_id,
                "{$this->sheet_name}!A1:E1"
            );

            return (bool) $response->getValues();
        } catch (Exception $e) {
            error_log('Google Sheets test failed: ' . $e->getMessage());
            return false;
        }
    }
}
