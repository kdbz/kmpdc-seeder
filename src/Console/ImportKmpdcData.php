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
use App\Models\Contact;

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
        $this->addUnknownForMissing(Status::class);

        $this->importSpecialities();
        $this->addUnknownForMissing(Speciality::class);

        $this->importSubSpecialities();
        $this->addUnknownForMissing(SubSpeciality::class);

        $this->importInstitutions();
        $this->addUnknownForMissing(Institution::class);

        $this->importDegrees();
        $this->addUnknownForMissing(Degree::class);

        // Step 2: Import practitioners (links to all others)
        $this->importPractitioners();

        $this->info("✅ All data imported successfully!");
        return Command::SUCCESS;
    }

    /* ------------------------------------------
     * 🧱 Individual Import Methods
     * ------------------------------------------ */

    public function addUnknownForMissing(string $modelClass)
    {
        $exists = $modelClass::where('name', 'UNKNOWN')->exists();
        if (!$exists) {
            if($modelClass==SubSpeciality::class){
                //need to link to a speciality
                $specialityId = Speciality::where('name', 'UNKNOWN')->value('id');
                $modelClass::create(['name' => 'UNKNOWN', 'speciality_id' => $specialityId]);
                return;
            }else{
                $modelClass::create(['name' => 'UNKNOWN']);
            }
        }
    }

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
                if($modelClass==Degree::class or $modelClass==Institution::class){
                    //return the model if it exists with name
                    $existing = $modelClass::where('name', trim($name))->first();

                    //then we update the abbrev field too
                    if($existing){
                        $existing->abbrev = substr(trim($name), 0, 15);
                        $existing->save();
                        $count++;
                        continue;
                    }else{
                        //create new with abbrev
                        $modelClass::create(['name' => trim($name), 'abbrev' => substr(trim($name), 0, 15)]);
                        $count++;
                        continue;
                    }

                }else{
                    $modelClass::updateOrCreate(['name' => trim($name)]);
                }

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
            //if not found, set to UNKNOWN
            if (!$specId) {
                $specId = Speciality::where('name', 'UNKNOWN')->value('id');
            }

            $subSpecId = SubSpeciality::where('name', $row['sub_speciality'] ?? '')->value('id');
            //if not found, set to UNKNOWN
            if (!$subSpecId) {
                $subSpecId = SubSpeciality::where('name', 'UNKNOWN')->value('id');
            }

            $practitionerId   = Practitioner::updateOrCreate(
                ['registration_number' => $row['registration_number']],
                [
                    'full_name' => $row['full_name'],
                    'status_id' => $statusId,
                    'speciality_id' => $specId,
                    'sub_speciality_id' => $subSpecId,
                ]
            );

            // insert contact information
            Contact::updateOrCreate(
                ['practitioner_id' => $practitionerId->id],
                [
                    'value' => $row['address'] ?? '',
                    'type' => 'address',
                    'is_primary' => true
                ]
            );

            // Link qualifications
            if (!empty($row['qualifications']) && is_array($row['qualifications']))
            {
                foreach ($row['qualifications'] as $qual) {
                    $degreeId = Degree::where('name', $qual['degree'] ?? '')->value('id');
                    //if not found, set to UNKNOWN
                    if (!$degreeId) {
                        $degreeId = Degree::where('name', 'UNKNOWN')->value('id');
                    }

                    $institutionId = Institution::where('name', $qual['institution'] ?? '')->value('id');
                    $specialityName = $qual['speciality'] ?? '';
                    $year = ($qual['year'] == "" ? 0 : $qual['year']);

                    $qualification = Qualification::firstOrCreate(
                        [
                            'practitioner_id' => $practitionerId->id,
                            'degree_id' => $degreeId,
                            'institution_id' => $institutionId,
                            'specialization' => $specialityName,
                            'year_awarded' => $year
                        ]
                    );

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
