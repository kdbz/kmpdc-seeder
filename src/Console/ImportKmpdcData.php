<?php

namespace Thibitisha\KmpdcSeeder\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\Status;
use App\Models\Speciality;
use App\Models\SubSpeciality;
use App\Models\Institution;
use App\Models\Practitioner;
use App\Models\Degree;
use App\Models\Qualification;

class ImportKmpdcData extends Command
{
    protected $signature = 'kmpdc:import';
    protected $description = 'Imports normalized practitioner data into database.';

    protected string $dataPath;

    public function handle()
    {
        $this->dataPath = storage_path('app/kmpdc-data');

        if (!File::exists($this->dataPath)) {
            $this->error("❌ Data path not found: {$this->dataPath}");
            return Command::FAILURE;
        }

        $this->info("📂 Importing data from {$this->dataPath}");

        // Step 1: Import reference tables
        $this->importStatuses();
        $this->importSpecialities();
        $this->importSubSpecialities();
        $this->importInstitutions();
        $this->importDegrees();

        // Step 2: Import practitioners (links to all others)
        $this->importPractitioners();


        $this->info("✅ All data imported successfully!");
        return Command::SUCCESS;
    }

    /* ------------------------------------------
     * 🧱 Individual Import Methods
     * ------------------------------------------ */

    protected function importStatuses()
    {
        $this->importSimpleJson('statuses.json', Status::class);
    }

    protected function importSpecialities()
    {
        $this->importSimpleJson('specialities.json', Speciality::class);
    }

    protected function importSubSpecialities()
    {
        $filename = 'subspecialities.json';
        $path = "{$this->dataPath}/{$filename}";

        if (!File::exists($path)) {
            $this->warn("⚠️ Missing file: {$filename}");
            return;
        }

        $data = json_decode(File::get($path), true);
        if (!is_array($data)) {
            $this->warn("⚠️ Invalid data in {$filename}");
            return;
        }

        $total = 0;
        foreach ($data as $specialityName => $subList) {
            // Find or create parent speciality
            $speciality = Speciality::updateOrCreate(['name' => trim($specialityName)]);

            if (!is_array($subList)) {
                continue;
            }

            foreach ($subList as $subName) {
                if (!empty($subName)) {
                    SubSpeciality::updateOrCreate(
                        [
                            'name' => trim(html_entity_decode($subName)),
                            'speciality_id' => $speciality->id,
                        ]
                    );
                    $total++;
                }
            }
        }

        $this->info("✅ Imported {$total} subspecialities from {$filename}");
    }

    protected function importDegrees()
    {
        $this->importSimpleJson('degrees.json', Degree::class);
    }   


    protected function importInstitutions()
    {
        $this->importSimpleJson('institutions.json', Institution::class);
    }

    /**
     * Generic loader for simple JSON (only has "name")
     */
    protected function importSimpleJson(string $filename, string $modelClass)
    {
        $path = "{$this->dataPath}/{$filename}";

        if (!File::exists($path)) {
            $this->warn("⚠️ Missing file: {$filename}");
            return;
        }

        $data = json_decode(File::get($path), true);
        if (!is_array($data)) {
            $this->warn("⚠️ Invalid data in {$filename}");
            return;
        }

        $count = 0;
        foreach ($data as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : $item;
            if ($name) {
                $modelClass::updateOrCreate(['name' => trim($name)]);
                $count++;
            }


            $this->info("✅ Imported {$count} records from {$filename}");
        }
    }

    /**
     * Imports practitioners and links them to their foreign keys.
     */
    protected function importPractitioners()
    {
        $path = "{$this->dataPath}/practitioners.json";
        if (!File::exists($path)) {
            $this->warn("⚠️ practitioners.json not found, skipping.");
            return;
        }

        $records = json_decode(File::get($path), true);
        if (!is_array($records)) {
            $this->warn("⚠️ Invalid practitioners.json structure");
            return;
        }

        $this->info("👩‍⚕️ Importing " . count($records) . " practitioners...");

        $count = 0;
        foreach ($records as $row) {
            $statusId = Status::where('name', $row['status'] ?? '')->value('id');
            $specId = Speciality::where('name', $row['speciality'] ?? '')->value('id');
            $subSpecId = SubSpeciality::where('name', $row['sub_speciality'] ?? '')->value('id');
            

            $practitionerId   = Practitioner::updateOrCreate(
                ['registration_number' => $row['registration_number']],
                [
                    'full_name' => $row['full_name'],
                    'status_id' => $statusId,
                    'speciality_id' => $specId,
                    'sub_speciality_id' => $subSpecId,
                ]
            );

            // Link qualifications
            if (!empty($row['qualifications']) && is_array($row['qualifications']))
            {
                foreach ($row['qualifications'] as $qual) {
                    $degreeId = Degree::where('name', $qual['degree'] ?? '')->value('id');
                    $institutionId = Institution::where('name', $qual['institution'] ?? '')->value('id');
                    $specialityName = $qual['speciality'] ?? null;
                    $year = $qual['year'] ?? null;
                    $qualification = Qualification::firstOrCreate(
                        [
                            'practitioner_id' => $practitionerId->id,
                            'degree_id' => $degreeId,
                            'institution_id' => $institutionId,
                            'speciality_name' => $specialityName,
                            'year_awarded' => $year
                        ]
                    );
                    
                    $practitionerId->qualifications()->syncWithoutDetaching($qualification->id);
                }
            }
            $count++;

            if ($count % 200 === 0) {
                $this->line("   → Imported {$count} practitioners...");
            }
        }

        $this->info("✅ Imported {$count} practitioners successfully.");
    }
}
